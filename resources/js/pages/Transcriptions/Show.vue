<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import { useToasts } from '@/composables/useToasts';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { translate as translateTranscription } from '@/routes/transcriptions';

const props = defineProps<{
    transcription: {
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
    };
}>();

const breadcrumbs = [
    {
        title: 'Transcribe',
        href: dashboard().url,
    },
    {
        title: props.transcription.filename,
        href: '',
    },
];

const progress = computed(() => {
    if (!props.transcription.chunks_total) {
        return 0;
    }

    return Math.round(
        (props.transcription.chunks_completed /
            props.transcription.chunks_total) *
            100,
    );
});

const statusLabel = computed(() => {
    switch (props.transcription.status) {
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
            return props.transcription.status;
    }
});

const statusColor = computed(() => {
    switch (props.transcription.status) {
        case 'completed':
            return 'text-emerald-500';
        case 'failed':
            return 'text-red-500';
        case 'awaiting-translation':
            return 'text-sky-500';
        case 'processing':
        case 'uploading':
        case 'uploaded':
            return 'text-amber-500';
        default:
            return 'text-muted-foreground';
    }
});

const statusBgColor = computed(() => {
    switch (props.transcription.status) {
        case 'completed':
            return 'bg-emerald-500';
        case 'failed':
            return 'bg-red-500';
        case 'awaiting-translation':
            return 'bg-sky-500';
        case 'processing':
        case 'uploading':
        case 'uploaded':
            return 'bg-amber-500';
        default:
            return 'bg-[var(--border)]';
    }
});

const progressStroke = computed(() => {
    switch (props.transcription.status) {
        case 'completed':
            return 'stroke-emerald-500';
        case 'failed':
            return 'stroke-red-500';
        case 'awaiting-translation':
            return 'stroke-sky-500';
        default:
            return 'stroke-amber-500';
    }
});

const isTerminal = computed(() =>
    ['completed', 'failed', 'awaiting-translation'].includes(
        props.transcription.status,
    ),
);

const isReadyForTranslation = computed(
    () => progress.value === 100 && props.transcription.status === 'awaiting-translation',
);

// Pipeline stages
const pipelineStages = computed(() => [
    {
        name: 'Upload',
        status: props.transcription.status !== 'uploading' ? 'complete' : 'active',
        icon: 'upload',
    },
    {
        name: 'Silence Detection',
        status: ['uploading', 'uploaded'].includes(props.transcription.status)
            ? 'pending'
            : props.transcription.chunks_total > 0
            ? 'complete'
            : 'pending',
        icon: 'waveform',
    },
    {
        name: 'Chunk Processing',
        status: props.transcription.status === 'processing'
            ? 'active'
            : progress.value === 100 || ['completed', 'awaiting-translation'].includes(props.transcription.status)
            ? 'complete'
            : 'pending',
        icon: 'queue',
    },
    {
        name: 'Translation',
        status: props.transcription.status === 'completed'
            ? 'complete'
            : props.transcription.status === 'awaiting-translation'
            ? 'active'
            : 'pending',
        icon: 'translate',
    },
]);

const { stop } = usePoll(2000);
const { pushToast } = useToasts();
const now = ref(Date.now());
let ticker: number | null = null;

watch(isTerminal, (value) => {
    if (value) {
        stop();
    }
});

// SVG circle calculations
const circleRadius = 54;
const circleCircumference = 2 * Math.PI * circleRadius;
const progressOffset = computed(() =>
    circleCircumference - (progress.value / 100) * circleCircumference
);

const elapsedSeconds = computed(() => {
    if (!props.transcription.created_at) {
        return null;
    }

    const createdAt = new Date(props.transcription.created_at).getTime();

    if (Number.isNaN(createdAt)) {
        return null;
    }

    return Math.max(0, Math.floor((now.value - createdAt) / 1000));
});

const estimatedSecondsRemaining = computed(() => {
    if (!elapsedSeconds.value || progress.value <= 0) {
        return null;
    }

    const estimatedTotal = Math.round(
        (elapsedSeconds.value / progress.value) * 100,
    );

    return Math.max(0, estimatedTotal - elapsedSeconds.value);
});

const formatSeconds = (value: number | null) => {
    if (value === null) {
        return '--';
    }

    const minutes = Math.floor(value / 60);
    const seconds = value % 60;

    if (minutes > 59) {
        const hours = Math.floor(minutes / 60);
        const remainderMinutes = minutes % 60;
        return `${hours}h ${remainderMinutes}m`;
    }

    return `${minutes}m ${seconds}s`;
};

const activeStageLabel = computed(() => {
    const activeStage = pipelineStages.value.find(
        (stage) => stage.status === 'active',
    );

    if (activeStage) {
        return activeStage.name;
    }

    if (isTerminal.value) {
        return 'Complete';
    }

    return 'Queued';
});

const refreshStatus = () => {
    router.reload({ only: ['transcription'] });
    pushToast('Refreshing status...', { variant: 'default' });
};

const copyLink = async () => {
    try {
        await navigator.clipboard.writeText(window.location.href);
        pushToast('Link copied to clipboard.', { variant: 'success' });
    } catch (error) {
        pushToast('Unable to copy link.', { variant: 'error' });
    }
};

const downloadAll = () => {
    const urls = [
        props.transcription.download_srt_url,
        props.transcription.download_vtt_url,
    ].filter(Boolean) as string[];

    urls.forEach((url) => {
        const link = document.createElement('a');
        link.href = url;
        link.target = '_blank';
        link.rel = 'noopener';
        document.body.appendChild(link);
        link.click();
        link.remove();
    });

    if (urls.length) {
        pushToast('Downloading subtitle files...', { variant: 'success' });
    }
};

const handleKeydown = (event: KeyboardEvent) => {
    const target = event.target as HTMLElement | null;
    const tagName = target?.tagName ?? '';

    if (target?.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(tagName)) {
        return;
    }

    if (event.shiftKey && event.key.toLowerCase() === 'r') {
        event.preventDefault();
        refreshStatus();
    }

    if (event.shiftKey && event.key.toLowerCase() === 'b') {
        event.preventDefault();
        router.visit(dashboard().url);
    }
};

onMounted(() => {
    ticker = setInterval(() => {
        now.value = Date.now();
    }, 1000);

    window.addEventListener('keydown', handleKeydown);
});

onBeforeUnmount(() => {
    if (ticker) {
        clearInterval(ticker);
    }

    window.removeEventListener('keydown', handleKeydown);
});

watch(isReadyForTranslation, (value, previous) => {
    if (value && !previous) {
        pushToast('Translation ready.', { variant: 'success' });
    }
});
</script>

<template>
    <Head :title="`Transcription - ${props.transcription.filename}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-10 px-6 pb-10 pt-6 lg:px-10">
            <!-- Hero Section with Progress Ring -->
            <section
                class="animate-transcribe-rise relative overflow-hidden rounded-3xl border border-[color:var(--border)]/70 bg-[var(--surface)]/80 p-6 shadow-[0_30px_70px_-50px_rgba(15,23,42,0.4)] backdrop-blur lg:p-10"
            >
                <div class="relative z-10 flex flex-col gap-8 lg:flex-row lg:items-start lg:justify-between">
                    <!-- Left: File info -->
                    <div class="flex flex-col gap-4">
                        <div class="flex items-center gap-3">
                            <div
                                class="h-3 w-3 rounded-full animate-pulse"
                                :class="statusBgColor"
                            />
                            <p
                                class="text-[11px] uppercase tracking-[0.5em]"
                                :class="statusColor"
                            >
                                {{ statusLabel }}
                            </p>
                        </div>
                        <h1
                            class="max-w-md text-3xl font-semibold text-[var(--text)] sm:text-4xl"
                        >
                            {{ props.transcription.filename }}
                        </h1>
                        <div class="flex flex-wrap items-center gap-4">
                            <span
                                class="rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)]/80 px-4 py-2 text-xs uppercase tracking-[0.2em] text-muted-foreground"
                            >
                                {{
                                    props.transcription.duration_seconds
                                        ? `${props.transcription.duration_seconds.toFixed(1)}s duration`
                                        : 'Detecting duration...'
                                }}
                            </span>
                            <span
                                class="rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)]/80 px-4 py-2 text-xs uppercase tracking-[0.2em] text-muted-foreground"
                            >
                                {{ props.transcription.chunks_total }} chunks
                            </span>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-3">
                            <Link
                                :href="dashboard()"
                                class="inline-flex items-center gap-2 text-xs uppercase tracking-[0.25em] text-muted-foreground transition hover:text-foreground"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                                </svg>
                                Back to queue
                            </Link>
                            <Button
                                variant="outline"
                                size="sm"
                                type="button"
                                class="rounded-full"
                                @click="refreshStatus"
                            >
                                Refresh status
                            </Button>
                            <span class="text-[10px] uppercase tracking-[0.25em] text-muted-foreground">
                                Shift + R refresh | Shift + B back
                            </span>
                        </div>
                    </div>

                    <!-- Right: Circular Progress -->
                    <div class="flex flex-col items-center gap-4">
                        <div class="relative">
                            <svg class="h-36 w-36 -rotate-90 transform" viewBox="0 0 120 120">
                                <!-- Background circle -->
                                <circle
                                    cx="60"
                                    cy="60"
                                :r="circleRadius"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="8"
                                class="text-[color:var(--border)]/60"
                            />
                                <!-- Progress circle -->
                                <circle
                                    cx="60"
                                    cy="60"
                                    :r="circleRadius"
                                    fill="none"
                                    stroke-width="8"
                                    stroke-linecap="round"
                                    :class="progressStroke"
                                    :stroke-dasharray="circleCircumference"
                                    :stroke-dashoffset="progressOffset"
                                    class="transition-all duration-700 ease-out"
                                />
                            </svg>
                            <!-- Center text -->
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span class="text-3xl font-semibold text-[var(--text)]">
                                    {{ progress }}%
                                </span>
                                <span class="text-[10px] uppercase tracking-[0.2em] text-muted-foreground">
                                    Complete
                                </span>
                            </div>
                        </div>
                        <div class="grid w-full max-w-[220px] grid-cols-2 gap-3 text-xs uppercase tracking-[0.2em] text-muted-foreground">
                            <div class="flex flex-col gap-1 rounded-xl border border-[color:var(--border)]/70 bg-[var(--surface)]/80 p-3 text-left">
                                <span>Elapsed</span>
                                <span class="text-sm font-semibold text-[var(--text)]">
                                    {{ formatSeconds(elapsedSeconds) }}
                                </span>
                            </div>
                            <div class="flex flex-col gap-1 rounded-xl border border-[color:var(--border)]/70 bg-[var(--surface)]/80 p-3 text-left">
                                <span>ETA</span>
                                <span class="text-sm font-semibold text-[var(--text)]">
                                    {{ formatSeconds(estimatedSecondsRemaining) }}
                                </span>
                            </div>
                            <div class="col-span-2 flex items-center justify-between rounded-xl border border-[color:var(--border)]/70 bg-[var(--surface)]/80 px-3 py-2">
                                <span>Stage</span>
                                <span class="text-xs font-semibold text-[var(--text)]">
                                    {{ activeStageLabel }}
                                </span>
                            </div>
                        </div>
                        <p
                            class="max-w-[200px] text-center text-xs text-muted-foreground"
                        >
                            {{ props.transcription.chunks_completed }} of {{ props.transcription.chunks_total }} chunks processed
                        </p>
                    </div>
                </div>

                <!-- Status Messages -->
                <div v-if="!isTerminal" class="mt-6">
                    <p
                        class="rounded-xl border border-amber-200/60 bg-amber-50/70 px-4 py-3 text-sm text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200"
                    >
                        <span class="mr-2 inline-block h-2 w-2 animate-pulse rounded-full bg-amber-500" />
                        This page polls automatically. Leave it open while the queue processes.
                    </p>
                </div>
                <div v-else-if="isReadyForTranslation" class="mt-6">
                    <div
                        class="flex flex-col gap-3 rounded-xl border border-sky-200/60 bg-sky-50/70 px-4 py-3 text-sm text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-200 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <p>
                            <span class="mr-2">✓</span>
                            Chunk processing complete. Ready to translate to English.
                        </p>
                        <Link
                            :href="translateTranscription({ transcription: props.transcription.id }).url"
                            method="post"
                            as="button"
                            class="inline-flex items-center justify-center rounded-full bg-primary px-5 py-2 text-[11px] font-semibold uppercase tracking-[0.3em] text-primary-foreground shadow-[0_18px_35px_-20px_rgba(15,23,42,0.4)] transition hover:-translate-y-0.5 hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
                        >
                            Translate now
                        </Link>
                    </div>
                </div>
                <div v-else-if="props.transcription.status === 'failed'" class="mt-6">
                    <div class="space-y-3">
                        <p
                            class="rounded-xl border border-red-200/60 bg-red-50/70 px-4 py-3 text-sm text-red-700 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-200"
                        >
                            <span class="mr-2">✕</span>
                            {{ props.transcription.error_message ?? 'Job failed unexpectedly.' }}
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <Link
                                :href="dashboard()"
                                class="inline-flex items-center rounded-full bg-primary px-4 py-2 text-xs font-semibold uppercase tracking-[0.2em] text-primary-foreground shadow-[0_12px_24px_-18px_rgba(15,23,42,0.35)]"
                            >
                                Start new run
                            </Link>
                            <Button
                                variant="outline"
                                size="sm"
                                type="button"
                                class="rounded-full"
                                @click="refreshStatus"
                            >
                                Retry status check
                            </Button>
                        </div>
                    </div>
                </div>
                <div v-else-if="props.transcription.status === 'completed'" class="mt-6">
                    <div class="space-y-3">
                        <p
                            class="rounded-xl border border-emerald-200/60 bg-emerald-50/70 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200"
                        >
                            <span class="mr-2">✓</span>
                            Transcription complete! Download your subtitle files below.
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                type="button"
                                class="rounded-full"
                                @click="downloadAll"
                            >
                                Download all
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                type="button"
                                class="rounded-full"
                                @click="copyLink"
                            >
                                Copy share link
                            </Button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Two-column: Output & Pipeline -->
            <section class="animate-transcribe-fade grid gap-6 lg:grid-cols-2" style="animation-delay: 0.15s">
                <!-- Output Downloads -->
                <div
                    class="rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/70 p-6 shadow-[0_18px_40px_-28px_rgba(15,23,42,0.3)] backdrop-blur-sm"
                >
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2
                                class="text-xl font-semibold text-[var(--text)]"
                            >
                                Output Files
                            </h2>
                            <p class="mt-2 text-sm text-muted-foreground">
                                Download your subtitle files once processing completes.
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                type="button"
                                class="rounded-full"
                                @click="copyLink"
                            >
                                Copy link
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                type="button"
                                class="rounded-full"
                                :disabled="!props.transcription.download_srt_url && !props.transcription.download_vtt_url"
                                @click="downloadAll"
                            >
                                Download all
                            </Button>
                        </div>
                    </div>

                    <div class="mt-6 flex flex-col gap-3">
                        <a
                            v-if="props.transcription.download_srt_url"
                            :href="props.transcription.download_srt_url"
                            class="group flex items-center justify-between rounded-xl border border-[color:var(--border)]/70 bg-[var(--surface)]/80 px-5 py-4 transition-all hover:-translate-y-0.5 hover:border-[color:var(--border)] hover:shadow-[0_15px_30px_-18px_rgba(15,23,42,0.25)]"
                        >
                            <div class="flex items-center gap-4">
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--accent)]">
                                    <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="font-semibold text-[var(--text)]">
                                        Download SRT
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        SubRip subtitle format
                                    </p>
                                </div>
                            </div>
                            <svg class="h-5 w-5 text-muted-foreground/60 transition-transform group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                        </a>

                        <a
                            v-if="props.transcription.download_vtt_url"
                            :href="props.transcription.download_vtt_url"
                            class="group flex items-center justify-between rounded-xl border border-[color:var(--border)]/70 bg-[var(--surface)]/80 px-5 py-4 transition-all hover:-translate-y-0.5 hover:border-[color:var(--border)] hover:shadow-[0_15px_30px_-18px_rgba(15,23,42,0.25)]"
                        >
                            <div class="flex items-center gap-4">
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg border border-[color:var(--border)]/70">
                                    <svg class="h-5 w-5 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="font-semibold text-[var(--text)]">
                                        Download VTT
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        WebVTT for web players
                                    </p>
                                </div>
                            </div>
                            <svg class="h-5 w-5 text-muted-foreground/60 transition-transform group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                        </a>

                        <div
                            v-if="!props.transcription.download_srt_url && !props.transcription.download_vtt_url"
                            class="flex items-center gap-4 rounded-xl border border-dashed border-[color:var(--border)]/70 px-5 py-8"
                        >
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--surface-2)]">
                                <svg class="h-5 w-5 animate-pulse text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm text-muted-foreground">
                                    Processing...
                                </p>
                                <p class="text-xs text-muted-foreground/80">
                                    Files will appear here when ready
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pipeline Timeline -->
                <div
                    class="rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/70 p-6 shadow-[0_18px_40px_-28px_rgba(15,23,42,0.3)] backdrop-blur-sm"
                >
                    <h2
                        class="text-xl font-semibold text-[var(--text)]"
                    >
                        Pipeline Progress
                    </h2>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Current stage of the transcription process.
                    </p>

                    <div class="mt-6 flex flex-col gap-0">
                        <div
                            v-for="(stage, index) in pipelineStages"
                            :key="stage.name"
                            class="relative flex items-start gap-4 pb-6"
                        >
                            <!-- Connecting line -->
                            <div
                                v-if="index < pipelineStages.length - 1"
                                class="absolute left-[15px] top-8 h-full w-0.5"
                                :class="stage.status === 'complete' ? 'bg-emerald-500' : 'bg-[var(--surface-2)]'"
                            />

                            <!-- Status dot -->
                            <div
                                class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                                :class="{
                                    'bg-emerald-500 shadow-[0_0_16px_rgba(52,211,153,0.5)]': stage.status === 'complete',
                                    'bg-amber-500 shadow-[0_0_16px_rgba(251,191,36,0.5)] animate-pulse': stage.status === 'active',
                                    'bg-[var(--surface-2)]': stage.status === 'pending',
                                }"
                            >
                                <svg
                                    v-if="stage.status === 'complete'"
                                    class="h-4 w-4 text-white"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                                </svg>
                                <div
                                    v-else-if="stage.status === 'active'"
                                    class="h-2 w-2 rounded-full bg-white"
                                />
                                <div
                                    v-else
                                    class="h-2 w-2 rounded-full bg-[var(--border)]"
                                />
                            </div>

                            <!-- Stage info -->
                            <div class="flex-1 pt-1">
                                <p
                                    class="font-medium"
                                    :class="{
                                        'text-emerald-600 dark:text-emerald-400': stage.status === 'complete',
                                        'text-amber-600 dark:text-amber-400': stage.status === 'active',
                                        'text-muted-foreground': stage.status === 'pending',
                                    }"
                                >
                                    {{ stage.name }}
                                </p>
                                <p class="text-xs text-muted-foreground/80">
                                    <template v-if="stage.status === 'complete'">Completed</template>
                                    <template v-else-if="stage.status === 'active'">In progress...</template>
                                    <template v-else>Waiting</template>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
