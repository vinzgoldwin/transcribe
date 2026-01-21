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

const isTerminal = computed(() =>
    ['completed', 'failed'].includes(props.transcription.status),
);

const { stop } = usePoll(2000);

watch(isTerminal, (value) => {
    if (value) {
        stop();
    }
});
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
        <div class="flex flex-col gap-8">
            <section
                class="animate-transcribe-rise relative overflow-hidden rounded-[32px] border border-black/10 bg-[radial-gradient(circle_at_10%_10%,_rgba(226,233,236,0.85),_rgba(236,221,205,0.55),_rgba(255,255,255,0.95))] p-6 shadow-[0_30px_80px_-60px_rgba(18,24,38,0.5)] dark:border-white/10 dark:bg-[radial-gradient(circle_at_10%_10%,_rgba(17,20,24,0.95),_rgba(12,14,18,0.88),_rgba(6,7,9,0.95))] lg:p-10"
            >
                <div
                    class="pointer-events-none absolute right-10 top-8 h-40 w-40 rounded-full bg-[radial-gradient(circle,_rgba(41,89,92,0.4),_transparent_65%)] blur-3xl"
                />
                <div
                    class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between"
                >
                    <div class="flex flex-col gap-3">
                        <p
                            class="text-xs uppercase tracking-[0.4em] text-slate-400"
                        >
                            Status
                        </p>
                        <h1
                            class="font-[Fraunces] text-3xl text-slate-900 dark:text-slate-100 sm:text-4xl"
                        >
                            {{ props.transcription.filename }}
                        </h1>
                        <p
                            class="font-[Manrope] text-sm text-slate-600 dark:text-slate-300"
                        >
                            {{ props.transcription.status }} -
                            {{ props.transcription.chunks_completed }} /
                            {{ props.transcription.chunks_total }} chunks
                        </p>
                    </div>

                    <div class="flex flex-col items-start gap-3">
                        <span
                            class="rounded-full border border-black/10 bg-white/70 px-4 py-2 text-xs uppercase tracking-[0.2em] text-slate-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-300"
                        >
                            {{
                                props.transcription.duration_seconds
                                    ? `${props.transcription.duration_seconds.toFixed(1)}s`
                                    : 'Duration pending'
                            }}
                        </span>
                        <Link
                            :href="dashboard()"
                            class="text-xs uppercase tracking-[0.25em] text-slate-400 hover:text-slate-600 dark:hover:text-white"
                        >
                            Back to queue
                        </Link>
                    </div>
                </div>

                <div class="mt-8 flex flex-col gap-4">
                    <div
                        class="flex items-center justify-between text-xs uppercase tracking-[0.25em] text-slate-400"
                    >
                        <span>Progress</span>
                        <span>{{ progress }}%</span>
                    </div>
                    <div
                        class="h-2 w-full overflow-hidden rounded-full bg-black/10 dark:bg-white/10"
                    >
                        <div
                            class="h-full rounded-full bg-slate-900 transition-all duration-700 dark:bg-white"
                            :style="{ width: `${progress}%` }"
                        />
                    </div>
                    <p
                        class="font-[Manrope] text-sm text-slate-600 dark:text-slate-300"
                    >
                        This page polls automatically. Leave it open while the
                        queue processes the chunks.
                    </p>
                    <p
                        v-if="props.transcription.status === 'failed'"
                        class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-200"
                    >
                        {{ props.transcription.error_message ?? 'Job failed.' }}
                    </p>
                </div>
            </section>

            <section class="animate-transcribe-fade grid gap-6 lg:grid-cols-2">
                <div
                    class="rounded-2xl border border-black/10 bg-white/70 p-6 font-[Manrope] text-sm text-slate-600 shadow-[0_18px_40px_-28px_rgba(15,23,42,0.35)] dark:border-white/10 dark:bg-white/5 dark:text-slate-300"
                >
                    <h2
                        class="font-[Fraunces] text-xl text-slate-900 dark:text-slate-100"
                    >
                        Output
                    </h2>
                    <p class="mt-2 text-sm">
                        Download subtitle files once processing completes.
                    </p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <a
                            v-if="props.transcription.download_srt_url"
                            :href="props.transcription.download_srt_url"
                            class="rounded-full bg-slate-900 px-4 py-2 text-xs uppercase tracking-[0.25em] text-white shadow-[0_20px_40px_-24px_rgba(15,23,42,0.45)] transition hover:-translate-y-[1px] dark:bg-white dark:text-slate-900"
                        >
                            Download SRT
                        </a>
                        <a
                            v-if="props.transcription.download_vtt_url"
                            :href="props.transcription.download_vtt_url"
                            class="rounded-full border border-black/10 px-4 py-2 text-xs uppercase tracking-[0.25em] text-slate-600 transition hover:text-slate-900 dark:border-white/10 dark:text-slate-300 dark:hover:text-white"
                        >
                            Download VTT
                        </a>
                        <span
                            v-if="
                                !props.transcription.download_srt_url &&
                                !props.transcription.download_vtt_url
                            "
                            class="text-xs uppercase tracking-[0.2em] text-slate-400"
                        >
                            Waiting for output...
                        </span>
                    </div>
                </div>

                <div
                    class="rounded-2xl border border-black/10 bg-white/70 p-6 font-[Manrope] text-sm text-slate-600 shadow-[0_18px_40px_-28px_rgba(15,23,42,0.35)] dark:border-white/10 dark:bg-white/5 dark:text-slate-300"
                >
                    <h2
                        class="font-[Fraunces] text-xl text-slate-900 dark:text-slate-100"
                    >
                        Pipeline detail
                    </h2>
                    <div class="mt-4 grid gap-3 text-xs uppercase tracking-[0.2em]">
                        <div
                            class="flex items-center justify-between rounded-xl border border-black/10 px-3 py-2 dark:border-white/10"
                        >
                            <span>Silence detect</span>
                            <span class="text-emerald-500">ready</span>
                        </div>
                        <div
                            class="flex items-center justify-between rounded-xl border border-black/10 px-3 py-2 dark:border-white/10"
                        >
                            <span>Chunk queue</span>
                            <span>{{ progress }}%</span>
                        </div>
                        <div
                            class="flex items-center justify-between rounded-xl border border-black/10 px-3 py-2 dark:border-white/10"
                        >
                            <span>Translation</span>
                            <span class="text-amber-500">
                                {{ props.transcription.status }}
                            </span>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
