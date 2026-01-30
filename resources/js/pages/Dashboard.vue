<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import AppLayout from '@/layouts/AppLayout.vue';
import { formatAbsoluteTime, formatRelativeTime } from '@/lib/utils';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';

import PlaceholderPattern from '../components/PlaceholderPattern.vue';

interface TranscriptionListItem {
    id: string;
    filename: string;
    status: string;
    created_at: string | null;
    show_url: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

const props = withDefaults(
    defineProps<{
        transcriptions?: TranscriptionListItem[];
    }>(),
    {
        transcriptions: () => [],
    },
);

const recentTranscriptions = computed(() =>
    props.transcriptions.slice(0, 3),
);

const now = ref(Date.now());
let timeTicker: number | null = null;

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

onMounted(() => {
    timeTicker = window.setInterval(() => {
        now.value = Date.now();
    }, 60000);
});

onBeforeUnmount(() => {
    if (timeTicker) {
        clearInterval(timeTicker);
    }
});
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="relative flex h-full flex-1 flex-col gap-8 overflow-x-auto rounded-3xl p-6 lg:p-10"
        >
            <div
                aria-hidden="true"
                class="pointer-events-none absolute inset-0 overflow-hidden"
            >
                <div
                    class="absolute -left-24 top-6 h-[20rem] w-[20rem] rounded-full bg-[radial-gradient(circle_at_center,hsl(332_80%_92%/0.45),transparent_70%)] blur-2xl"
                ></div>
                <div
                    class="absolute right-0 top-[35%] h-[24rem] w-[24rem] rounded-full bg-[radial-gradient(circle_at_center,hsl(210_80%_92%/0.35),transparent_70%)] blur-2xl"
                ></div>
            </div>

            <section
                class="panel-noise relative flex flex-wrap items-center justify-between gap-6 rounded-3xl border border-[color:var(--border)]/70 bg-[var(--surface)]/75 p-6 shadow-[0_24px_60px_-38px_rgba(15,23,42,0.35)] backdrop-blur"
            >
                <div class="space-y-2">
                    <p
                        class="text-xs font-semibold uppercase tracking-[0.35em] text-muted-foreground"
                    >
                        Dashboard
                    </p>
                    <h1 class="text-2xl font-semibold text-[var(--text)]">
                        Operational overview
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Live pipeline health, cadence control, and recent
                        exports.
                    </p>
                </div>
                <div
                    class="text-xs font-semibold uppercase tracking-[0.25em] text-muted-foreground"
                >
                    98.6% accuracy · 24h window
                </div>
            </section>

            <section
                class="panel-noise relative rounded-3xl border border-[color:var(--border)]/70 bg-[var(--surface)]/80 p-6 shadow-[0_24px_60px_-38px_rgba(15,23,42,0.35)] lg:p-8"
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="space-y-2">
                        <p
                            class="text-xs font-semibold uppercase tracking-[0.35em] text-muted-foreground"
                        >
                            Command center
                        </p>
                        <h2 class="text-xl font-semibold text-[var(--text)]">
                            Live workspace
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Review recent work and keep the pipeline steady
                            without the noise.
                        </p>
                    </div>
                    <div
                        class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-[0.25em] text-muted-foreground"
                    >
                        <span
                            class="rounded-full border border-[color:var(--border)]/70 bg-[var(--surface-2)]/70 px-3 py-1"
                        >
                            98.6% accuracy
                        </span>
                        <span
                            class="rounded-full border border-[color:var(--border)]/70 bg-[var(--surface-2)]/70 px-3 py-1"
                        >
                            2 in queue
                        </span>
                        <span
                            class="rounded-full bg-[var(--accent-soft)] px-3 py-1 text-[var(--accent)]"
                        >
                            Stable
                        </span>
                    </div>
                </div>

                <div class="mt-6 grid gap-6 lg:grid-cols-[1.35fr_0.65fr]">
                    <div>
                        <div
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <div>
                                <h3
                                    class="text-base font-semibold text-[var(--text)]"
                                >
                                    Recent activity
                                </h3>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Last three transcription runs.
                                </p>
                            </div>
                            <span
                                class="text-xs uppercase tracking-[0.3em] text-muted-foreground"
                            >
                                {{ recentTranscriptions.length }} shown
                            </span>
                        </div>

                        <ol
                            v-if="recentTranscriptions.length"
                            class="mt-4 divide-y divide-[color:var(--border)]/70"
                        >
                            <li
                                v-for="item in recentTranscriptions"
                                :key="item.id"
                            >
                                <Link
                                    :href="item.show_url"
                                    class="group flex items-center justify-between gap-4 py-4 text-sm transition hover:-translate-y-0.5"
                                >
                                    <div class="min-w-0">
                                        <p
                                            class="truncate font-semibold text-[var(--text)] transition group-hover:text-[var(--accent)]"
                                        >
                                            {{ item.filename }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                            :title="formatAbsoluteTime(item.created_at)"
                                        >
                                            {{
                                                item.created_at
                                                    ? formatRelativeTime(
                                                          item.created_at,
                                                          now.value,
                                                      )
                                                    : 'Just now'
                                            }}
                                        </p>
                                    </div>
                                    <span
                                        class="status-pill shrink-0"
                                        :class="statusColor(item.status)"
                                    >
                                        {{ formatStatus(item.status) }}
                                    </span>
                                </Link>
                            </li>
                        </ol>
                        <div
                            v-else
                            class="mt-4 rounded-2xl border border-dashed border-[color:var(--border)]/70 bg-[var(--surface)]/70 px-4 py-6 text-sm text-muted-foreground"
                        >
                            No recent activity yet. Start a transcription to see
                            updates here.
                        </div>
                    </div>

                    <aside
                        class="relative overflow-hidden rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/80 p-5"
                    >
                        <PlaceholderPattern class="opacity-50" />
                        <div class="relative space-y-4">
                            <div class="flex items-center justify-between">
                                <p
                                    class="text-xs font-semibold uppercase tracking-[0.35em] text-muted-foreground"
                                >
                                    Pipeline focus
                                </p>
                                <span
                                    class="rounded-full bg-[var(--accent-soft)] px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.25em] text-[var(--accent)]"
                                >
                                    Stable
                                </span>
                            </div>
                            <div class="rounded-2xl bg-[var(--surface-2)]/80 p-4">
                                <p
                                    class="text-sm font-semibold text-[var(--text)]"
                                >
                                    Active translation
                                </p>
                                <p class="mt-2 text-xs text-muted-foreground">
                                    Adaptive models calibrate on your last
                                    exports.
                                </p>
                                <div
                                    class="mt-4 h-1.5 w-full rounded-full bg-white/80"
                                >
                                    <div
                                        class="h-full w-[64%] rounded-full bg-primary shadow-[0_8px_20px_-10px_rgba(182,62,117,0.7)]"
                                    ></div>
                                </div>
                            </div>
                            <div class="space-y-3 text-xs text-muted-foreground">
                                <div class="flex items-center justify-between">
                                    <span>Queue</span>
                                    <span
                                        class="font-semibold text-[var(--text)]"
                                    >
                                        2 files
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span>AI confidence</span>
                                    <span
                                        class="font-semibold text-[var(--text)]"
                                    >
                                        98.6%
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span>Reviewer backlog</span>
                                    <span
                                        class="font-semibold text-[var(--text)]"
                                    >
                                        1 ready
                                    </span>
                                </div>
                            </div>
                            <div
                                class="rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface-2)]/60 px-4 py-3 text-xs text-muted-foreground"
                            >
                                <p class="font-semibold text-[var(--text)]">
                                    Next checkpoint
                                </p>
                                <p class="mt-1">
                                    Automatic review run in 32 minutes.
                                </p>
                            </div>
                        </div>
                    </aside>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
