<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';

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
            return 'text-slate-400';
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
            return 'bg-slate-400';
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
</script>

<template>
    <Head :title="`Transcription - ${props.transcription.filename}`">
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
        <div class="flex flex-col gap-8 p-4 lg:p-8">
            <!-- Hero Section with Progress Ring -->
            <section
                class="animate-transcribe-rise relative overflow-hidden rounded-[32px] border border-black/10 bg-[radial-gradient(ellipse_at_top_left,_rgba(255,255,255,0.95),_rgba(226,233,236,0.7),_rgba(236,221,205,0.5))] p-6 shadow-[0_30px_80px_-60px_rgba(18,24,38,0.5)] dark:border-white/10 dark:bg-[radial-gradient(ellipse_at_top_left,_rgba(20,22,27,0.98),_rgba(15,18,24,0.9),_rgba(8,10,14,0.95))] lg:p-10"
            >
                <!-- Decorative elements -->
                <div
                    class="pointer-events-none absolute -right-20 -top-20 h-64 w-64 rounded-full bg-[radial-gradient(circle,_rgba(43,82,84,0.35),_transparent_60%)] blur-3xl"
                />
                <div
                    class="pointer-events-none absolute -bottom-32 left-20 h-80 w-80 rounded-full bg-[radial-gradient(circle,_rgba(232,135,64,0.25),_transparent_65%)] blur-3xl"
                />

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
                            class="max-w-md font-[Fraunces] text-3xl text-slate-900 dark:text-slate-100 sm:text-4xl"
                        >
                            {{ props.transcription.filename }}
                        </h1>
                        <div class="flex flex-wrap items-center gap-4">
                            <span
                                class="rounded-full border border-black/10 bg-white/70 px-4 py-2 text-xs uppercase tracking-[0.2em] text-slate-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-300"
                            >
                                {{
                                    props.transcription.duration_seconds
                                        ? `${props.transcription.duration_seconds.toFixed(1)}s duration`
                                        : 'Detecting duration...'
                                }}
                            </span>
                            <span
                                class="rounded-full border border-black/10 bg-white/70 px-4 py-2 text-xs uppercase tracking-[0.2em] text-slate-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-300"
                            >
                                {{ props.transcription.chunks_total }} chunks
                            </span>
                        </div>
                        <Link
                            :href="dashboard()"
                            class="mt-2 inline-flex items-center gap-2 text-xs uppercase tracking-[0.25em] text-slate-400 transition hover:text-slate-600 dark:hover:text-white"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                            Back to queue
                        </Link>
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
                                    class="text-black/5 dark:text-white/10"
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
                                <span class="font-[Fraunces] text-3xl text-slate-900 dark:text-white">
                                    {{ progress }}%
                                </span>
                                <span class="text-[10px] uppercase tracking-[0.2em] text-slate-400">
                                    Complete
                                </span>
                            </div>
                        </div>
                        <p
                            class="max-w-[200px] text-center font-[Manrope] text-xs text-slate-500 dark:text-slate-400"
                        >
                            {{ props.transcription.chunks_completed }} of {{ props.transcription.chunks_total }} chunks processed
                        </p>
                    </div>
                </div>

                <!-- Status Messages -->
                <div v-if="!isTerminal" class="mt-6">
                    <p
                        class="rounded-xl border border-amber-200/50 bg-amber-50/50 px-4 py-3 font-[Manrope] text-sm text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200"
                    >
                        <span class="mr-2 inline-block h-2 w-2 animate-pulse rounded-full bg-amber-500" />
                        This page polls automatically. Leave it open while the queue processes.
                    </p>
                </div>
                <div v-else-if="props.transcription.status === 'awaiting-translation'" class="mt-6">
                    <p
                        class="rounded-xl border border-sky-200/50 bg-sky-50/50 px-4 py-3 font-[Manrope] text-sm text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-200"
                    >
                        <span class="mr-2">✓</span>
                        Whisper completed. Download the SRT and translate when ready.
                    </p>
                </div>
                <div v-else-if="props.transcription.status === 'failed'" class="mt-6">
                    <p
                        class="rounded-xl border border-red-200/50 bg-red-50/50 px-4 py-3 font-[Manrope] text-sm text-red-700 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-200"
                    >
                        <span class="mr-2">✕</span>
                        {{ props.transcription.error_message ?? 'Job failed unexpectedly.' }}
                    </p>
                </div>
                <div v-else-if="props.transcription.status === 'completed'" class="mt-6">
                    <p
                        class="rounded-xl border border-emerald-200/50 bg-emerald-50/50 px-4 py-3 font-[Manrope] text-sm text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200"
                    >
                        <span class="mr-2">✓</span>
                        Transcription complete! Download your subtitle files below.
                    </p>
                </div>
            </section>

            <!-- Two-column: Output & Pipeline -->
            <section class="animate-transcribe-fade grid gap-6 lg:grid-cols-2" style="animation-delay: 0.15s">
                <!-- Output Downloads -->
                <div
                    class="rounded-2xl border border-black/10 bg-white/70 p-6 shadow-[0_18px_40px_-28px_rgba(15,23,42,0.35)] backdrop-blur-sm dark:border-white/10 dark:bg-white/5"
                >
                    <h2
                        class="font-[Fraunces] text-xl text-slate-900 dark:text-slate-100"
                    >
                        Output Files
                    </h2>
                    <p class="mt-2 font-[Manrope] text-sm text-slate-500 dark:text-slate-400">
                        Download your subtitle files once processing completes.
                    </p>

                    <div class="mt-6 flex flex-col gap-3">
                        <a
                            v-if="props.transcription.download_srt_url"
                            :href="props.transcription.download_srt_url"
                            class="group flex items-center justify-between rounded-xl border border-black/10 bg-white/80 px-5 py-4 transition-all hover:-translate-y-0.5 hover:border-black/20 hover:shadow-[0_15px_30px_-15px_rgba(15,23,42,0.25)] dark:border-white/10 dark:bg-white/5 dark:hover:border-white/20"
                        >
                            <div class="flex items-center gap-4">
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-900 dark:bg-white">
                                    <svg class="h-5 w-5 text-white dark:text-slate-900" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="font-[Manrope] font-semibold text-slate-900 dark:text-white">
                                        Download SRT
                                    </p>
                                    <p class="text-xs text-slate-400">
                                        SubRip subtitle format
                                    </p>
                                </div>
                            </div>
                            <svg class="h-5 w-5 text-slate-300 transition-transform group-hover:translate-x-1 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                        </a>

                        <a
                            v-if="props.transcription.download_vtt_url"
                            :href="props.transcription.download_vtt_url"
                            class="group flex items-center justify-between rounded-xl border border-black/10 bg-white/80 px-5 py-4 transition-all hover:-translate-y-0.5 hover:border-black/20 hover:shadow-[0_15px_30px_-15px_rgba(15,23,42,0.25)] dark:border-white/10 dark:bg-white/5 dark:hover:border-white/20"
                        >
                            <div class="flex items-center gap-4">
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg border border-black/10 dark:border-white/10">
                                    <svg class="h-5 w-5 text-slate-600 dark:text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="font-[Manrope] font-semibold text-slate-900 dark:text-white">
                                        Download VTT
                                    </p>
                                    <p class="text-xs text-slate-400">
                                        WebVTT for web players
                                    </p>
                                </div>
                            </div>
                            <svg class="h-5 w-5 text-slate-300 transition-transform group-hover:translate-x-1 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                        </a>

                        <div
                            v-if="!props.transcription.download_srt_url && !props.transcription.download_vtt_url"
                            class="flex items-center gap-4 rounded-xl border border-dashed border-black/10 px-5 py-8 dark:border-white/10"
                        >
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 dark:bg-white/10">
                                <svg class="h-5 w-5 animate-pulse text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div>
                                <p class="font-[Manrope] text-sm text-slate-500 dark:text-slate-400">
                                    Processing...
                                </p>
                                <p class="text-xs text-slate-400 dark:text-slate-500">
                                    Files will appear here when ready
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pipeline Timeline -->
                <div
                    class="rounded-2xl border border-black/10 bg-white/70 p-6 shadow-[0_18px_40px_-28px_rgba(15,23,42,0.35)] backdrop-blur-sm dark:border-white/10 dark:bg-white/5"
                >
                    <h2
                        class="font-[Fraunces] text-xl text-slate-900 dark:text-slate-100"
                    >
                        Pipeline Progress
                    </h2>
                    <p class="mt-2 font-[Manrope] text-sm text-slate-500 dark:text-slate-400">
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
                                :class="stage.status === 'complete' ? 'bg-emerald-500' : 'bg-slate-200 dark:bg-white/10'"
                            />

                            <!-- Status dot -->
                            <div
                                class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                                :class="{
                                    'bg-emerald-500 shadow-[0_0_16px_rgba(52,211,153,0.5)]': stage.status === 'complete',
                                    'bg-amber-500 shadow-[0_0_16px_rgba(251,191,36,0.5)] animate-pulse': stage.status === 'active',
                                    'bg-slate-200 dark:bg-white/10': stage.status === 'pending',
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
                                    class="h-2 w-2 rounded-full bg-slate-400 dark:bg-slate-600"
                                />
                            </div>

                            <!-- Stage info -->
                            <div class="flex-1 pt-1">
                                <p
                                    class="font-[Manrope] font-medium"
                                    :class="{
                                        'text-emerald-600 dark:text-emerald-400': stage.status === 'complete',
                                        'text-amber-600 dark:text-amber-400': stage.status === 'active',
                                        'text-slate-400': stage.status === 'pending',
                                    }"
                                >
                                    {{ stage.name }}
                                </p>
                                <p class="text-xs text-slate-400 dark:text-slate-500">
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
