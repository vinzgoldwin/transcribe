<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import AppLayout from '@/layouts/AppLayout.vue';
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
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-2xl p-6"
        >
            <section
                class="rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/70 p-6 shadow-[0_18px_40px_-28px_rgba(15,23,42,0.3)]"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-[var(--text)]">
                            Recent activity
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Last three transcription runs.
                        </p>
                    </div>
                    <span class="text-xs uppercase tracking-[0.3em] text-muted-foreground">
                        {{ recentTranscriptions.length }} shown
                    </span>
                </div>

                <div v-if="recentTranscriptions.length" class="mt-4 grid gap-2">
                    <Link
                        v-for="item in recentTranscriptions"
                        :key="item.id"
                        :href="item.show_url"
                        class="flex items-center justify-between gap-4 rounded-xl border border-[color:var(--border)]/70 bg-[var(--surface)]/80 px-4 py-3 text-sm transition hover:bg-[var(--surface-2)]"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-[var(--text)]">
                                {{ item.filename }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ item.created_at ?? 'Just now' }}
                            </p>
                        </div>
                        <span
                            class="shrink-0 rounded-full border border-[color:var(--border)]/70 px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.2em]"
                            :class="statusColor(item.status)"
                        >
                            {{ formatStatus(item.status) }}
                        </span>
                    </Link>
                </div>
                <div
                    v-else
                    class="mt-4 rounded-xl border border-dashed border-[color:var(--border)]/70 bg-[var(--surface)]/60 px-4 py-6 text-sm text-muted-foreground"
                >
                    No recent activity yet. Start a transcription to see updates here.
                </div>
            </section>
            <div class="grid auto-rows-min gap-4 md:grid-cols-3">
                <div
                    class="relative aspect-video overflow-hidden rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/70 shadow-[0_12px_30px_-24px_rgba(15,23,42,0.25)]"
                >
                    <PlaceholderPattern />
                </div>
                <div
                    class="relative aspect-video overflow-hidden rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/70 shadow-[0_12px_30px_-24px_rgba(15,23,42,0.25)]"
                >
                    <PlaceholderPattern />
                </div>
                <div
                    class="relative aspect-video overflow-hidden rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/70 shadow-[0_12px_30px_-24px_rgba(15,23,42,0.25)]"
                >
                    <PlaceholderPattern />
                </div>
            </div>
            <div
                class="relative min-h-[100vh] flex-1 rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/70 shadow-[0_12px_30px_-24px_rgba(15,23,42,0.25)] md:min-h-min"
            >
                <PlaceholderPattern />
            </div>
        </div>
    </AppLayout>
</template>
