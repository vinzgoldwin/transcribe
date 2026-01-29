<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

import AppLogo from '@/components/AppLogo.vue';
import { dashboard, login } from '@/routes';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);
</script>

<template>
    <Head title="Welcome" />

    <div class="min-h-screen bg-[var(--bg)] text-[var(--text)]">
        <header
            class="mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-6"
        >
            <div class="flex items-center gap-3">
                <AppLogo />
            </div>
            <nav class="flex items-center gap-2 text-sm font-medium">
                <Link
                    v-if="$page.props.auth.user"
                    :href="dashboard()"
                    class="inline-flex items-center rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)] px-4 py-2 text-muted-foreground shadow-[0_10px_24px_-20px_rgba(15,23,42,0.3)] transition hover:text-foreground"
                >
                    Dashboard
                </Link>
                <Link
                    v-else
                    :href="login()"
                    class="inline-flex items-center rounded-full bg-primary px-4 py-2 text-primary-foreground shadow-[0_12px_28px_-18px_rgba(15,23,42,0.35)] transition hover:bg-primary/90"
                >
                    Log in
                </Link>
            </nav>
        </header>

        <main
            class="mx-auto grid w-full max-w-6xl gap-10 px-6 pb-16 pt-6 lg:grid-cols-[1.1fr_0.9fr]"
        >
            <section class="flex flex-col justify-center gap-6">
                <p
                    class="text-xs font-semibold uppercase tracking-[0.35em] text-muted-foreground"
                >
                    Transcribe
                </p>
                <h1
                    class="text-4xl font-semibold tracking-[-0.02em] lg:text-5xl"
                >
                    Japanese audio to broadcast-ready subtitles.
                </h1>
                <p class="max-w-prose text-base text-muted-foreground">
                    Upload an MP4, let the pipeline handle silence-aware
                    chunking, speech-to-text, translation, and timing. Get
                    clean SRT or VTT output with consistent cadence.
                </p>
                <div class="flex flex-wrap gap-3">
                    <span
                        class="rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)] px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground"
                    >
                        Direct upload
                    </span>
                    <span
                        class="rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)] px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground"
                    >
                        Silence-aware
                    </span>
                    <span
                        class="rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)] px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground"
                    >
                        JP to EN
                    </span>
                </div>
                <div class="flex flex-wrap gap-3">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="dashboard()"
                        class="inline-flex items-center rounded-full bg-primary px-5 py-2 text-sm font-semibold text-primary-foreground shadow-[0_14px_32px_-18px_rgba(15,23,42,0.4)] transition hover:bg-primary/90"
                    >
                        Open dashboard
                    </Link>
                    <Link
                        v-else
                        :href="login()"
                        class="inline-flex items-center rounded-full bg-primary px-5 py-2 text-sm font-semibold text-primary-foreground shadow-[0_14px_32px_-18px_rgba(15,23,42,0.4)] transition hover:bg-primary/90"
                    >
                        Start transcribing
                    </Link>
                    <span
                        class="inline-flex items-center rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)] px-4 py-2 text-xs font-medium text-muted-foreground"
                    >
                        Direct-to-storage uploads only
                    </span>
                </div>
            </section>

            <aside
                class="flex flex-col gap-6 rounded-3xl border border-[color:var(--border)]/70 bg-[var(--surface)]/80 p-6 shadow-[0_25px_60px_-45px_rgba(15,23,42,0.45)] backdrop-blur"
            >
                <div class="flex items-center justify-between">
                    <p
                        class="text-xs font-semibold uppercase tracking-[0.3em] text-muted-foreground"
                    >
                        Pipeline
                    </p>
                    <span
                        class="rounded-full bg-[var(--accent-soft)] px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.2em] text-[var(--accent)]"
                    >
                        Live
                    </span>
                </div>
                <div class="space-y-4">
                    <div class="flex items-start gap-3">
                        <span
                            class="mt-1 h-2 w-2 rounded-full bg-[var(--accent)] shadow-[0_0_12px_rgba(58,130,246,0.5)]"
                        />
                        <div>
                            <p class="text-sm font-semibold">
                                Upload to storage
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Presigned MP4 upload with expiring links.
                            </p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span
                            class="mt-1 h-2 w-2 rounded-full bg-[var(--surface-2)]"
                        />
                        <div>
                            <p class="text-sm font-semibold">
                                Silence detection
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Chunked for pacing and readability.
                            </p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span
                            class="mt-1 h-2 w-2 rounded-full bg-[var(--surface-2)]"
                        />
                        <div>
                            <p class="text-sm font-semibold">
                                Translate + export
                            </p>
                            <p class="text-xs text-muted-foreground">
                                SRT/VTT with broadcast-safe limits.
                            </p>
                        </div>
                    </div>
                </div>
                <div
                    class="rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface-2)] p-4 text-xs text-muted-foreground"
                >
                    Tune output with 42 chars per line, 2 lines max, and 1-6s
                    duration caps.
                </div>
            </aside>
        </main>
    </div>
</template>
