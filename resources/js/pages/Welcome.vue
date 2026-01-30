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

    <div
        class="relative min-h-screen overflow-hidden bg-[var(--bg)] text-[var(--text)]"
    >
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-0"
        >
            <div
                class="absolute -left-36 top-[-20%] h-[28rem] w-[28rem] rounded-full bg-[radial-gradient(circle_at_center,hsl(332_80%_92%/0.75),transparent_70%)] blur-2xl"
            ></div>
            <div
                class="absolute right-[-10%] top-[8%] h-[26rem] w-[26rem] rounded-full bg-[radial-gradient(circle_at_center,hsl(210_80%_92%/0.6),transparent_70%)] blur-2xl"
            ></div>
            <div
                class="absolute bottom-[-25%] left-[15%] h-[30rem] w-[30rem] rounded-full bg-[radial-gradient(circle_at_center,hsl(332_70%_94%/0.6),transparent_70%)] blur-3xl"
            ></div>
        </div>

        <header
            class="relative mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-6 lg:py-8"
        >
            <div class="flex items-center gap-3">
                <AppLogo />
                <span
                    class="rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)]/70 px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.3em] text-muted-foreground"
                >
                    Studio
                </span>
            </div>
            <nav class="flex items-center gap-2 text-sm font-medium">
                <Link
                    v-if="$page.props.auth.user"
                    :href="dashboard()"
                    class="inline-flex items-center rounded-full border border-[color:var(--border)]/80 bg-[var(--surface)]/80 px-4 py-2 text-[13px] font-semibold text-muted-foreground shadow-[0_16px_40px_-30px_rgba(15,23,42,0.35)] transition hover:border-[color:var(--accent)]/40 hover:text-[var(--text)]"
                >
                    Dashboard
                </Link>
                <Link
                    v-else
                    :href="login()"
                    class="inline-flex items-center rounded-full bg-primary px-4 py-2 text-[13px] font-semibold text-primary-foreground shadow-[0_18px_45px_-28px_rgba(182,62,117,0.6)] transition hover:-translate-y-0.5 hover:bg-primary/90"
                >
                    Log in
                </Link>
            </nav>
        </header>

        <main
            class="relative mx-auto grid w-full max-w-6xl gap-12 px-6 pb-20 pt-4 lg:grid-cols-[1.1fr_0.9fr]"
        >
            <section class="flex flex-col justify-center gap-6">
                <p
                    class="text-xs font-semibold uppercase tracking-[0.45em] text-muted-foreground motion-safe:animate-transcribe-fade"
                    style="animation-delay: 0.05s"
                >
                    Studio-grade subtitles for Japanese audio
                </p>
                <h1
                    class="text-4xl font-semibold leading-tight tracking-[-0.03em] text-[var(--text)] sm:text-5xl lg:text-6xl"
                >
                    <span class="font-serif">
                        The cleanest way to publish
                    </span>
                    subtitle-ready translations.
                </h1>
                <p
                    class="max-w-xl text-base text-muted-foreground sm:text-lg motion-safe:animate-transcribe-rise"
                    style="animation-delay: 0.12s"
                >
                    Drop your MP4 and let the pipeline handle the rest: pause
                    detection, transcription, translation, and timing that keeps
                    every line readable on screen.
                </p>
                <div
                    class="flex flex-wrap items-center gap-3 motion-safe:animate-transcribe-rise"
                    style="animation-delay: 0.2s"
                >
                    <Link
                        v-if="$page.props.auth.user"
                        :href="dashboard()"
                        class="inline-flex items-center rounded-full bg-primary px-6 py-3 text-sm font-semibold text-primary-foreground shadow-[0_20px_50px_-28px_rgba(182,62,117,0.65)] transition hover:-translate-y-0.5 hover:bg-primary/90"
                    >
                        Open dashboard
                    </Link>
                    <Link
                        v-else
                        :href="login()"
                        class="inline-flex items-center rounded-full bg-primary px-6 py-3 text-sm font-semibold text-primary-foreground shadow-[0_20px_50px_-28px_rgba(182,62,117,0.65)] transition hover:-translate-y-0.5 hover:bg-primary/90"
                    >
                        Start transcribing
                    </Link>
                    <span
                        class="text-xs font-semibold uppercase tracking-[0.3em] text-muted-foreground"
                    >
                        Secure, direct uploads
                    </span>
                </div>
            </section>

            <aside
                class="relative flex flex-col gap-6 rounded-3xl border border-[color:var(--border)]/70 bg-[var(--surface)]/80 p-6 shadow-[0_30px_70px_-45px_rgba(15,23,42,0.45)] backdrop-blur"
            >
                <div class="flex items-center justify-between">
                    <p
                        class="text-xs font-semibold uppercase tracking-[0.35em] text-muted-foreground"
                    >
                        Pipeline
                    </p>
                    <span
                        class="rounded-full bg-[var(--accent-soft)] px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.25em] text-[var(--accent)]"
                    >
                        Live
                    </span>
                </div>

                <div
                    class="rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface-2)]/80 p-4"
                >
                    <p class="text-sm font-semibold text-[var(--text)]">
                        Built for broadcast rhythm
                    </p>
                    <p class="mt-2 text-xs text-muted-foreground">
                        Two-line captions, balanced on-screen timing, automatic
                        readability checks.
                    </p>
                    <div class="mt-4 h-1.5 w-full rounded-full bg-white">
                        <div
                            class="h-full w-[72%] rounded-full bg-primary shadow-[0_8px_20px_-10px_rgba(182,62,117,0.7)]"
                        ></div>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="flex items-start gap-3">
                        <span
                            class="mt-1 h-2.5 w-2.5 rounded-full bg-[var(--accent)] shadow-[0_0_16px_rgba(182,62,117,0.55)]"
                        />
                        <div>
                            <p class="text-sm font-semibold">Upload + verify</p>
                            <p class="text-xs text-muted-foreground">
                                Presigned storage with secure checksums.
                            </p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span
                            class="mt-1 h-2.5 w-2.5 rounded-full bg-[var(--surface-2)]"
                        />
                        <div>
                            <p class="text-sm font-semibold">
                                Detect pauses
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Chunking tuned for subtitle pacing.
                            </p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span
                            class="mt-1 h-2.5 w-2.5 rounded-full bg-[var(--surface-2)]"
                        />
                        <div>
                            <p class="text-sm font-semibold">
                                Transcribe
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Japanese audio to clean text.
                            </p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span
                            class="mt-1 h-2.5 w-2.5 rounded-full bg-[var(--surface-2)]"
                        />
                        <div>
                            <p class="text-sm font-semibold">Translate</p>
                            <p class="text-xs text-muted-foreground">
                                Natural English with consistent tone.
                            </p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span
                            class="mt-1 h-2.5 w-2.5 rounded-full bg-[var(--surface-2)]"
                        />
                        <div>
                            <p class="text-sm font-semibold">Export</p>
                            <p class="text-xs text-muted-foreground">
                                Subtitle-ready SRT + VTT delivery.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl bg-[var(--surface)]/70 p-4">
                    <p
                        class="text-xs font-semibold uppercase tracking-[0.3em] text-muted-foreground"
                    >
                        How it moves
                    </p>
                    <div class="mt-4 space-y-3 text-xs text-muted-foreground">
                        <div class="flex items-center justify-between">
                            <span>Upload</span>
                            <span class="font-semibold text-[var(--text)]">
                                02:05
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Transcribe</span>
                            <span class="font-semibold text-[var(--text)]">
                                01:18
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Translate</span>
                            <span class="font-semibold text-[var(--text)]">
                                00:44
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Export</span>
                            <span class="font-semibold text-[var(--text)]">
                                00:10
                            </span>
                        </div>
                    </div>
                    <div
                        class="relative mt-4 h-1.5 overflow-hidden rounded-full bg-[var(--surface-2)]"
                    >
                        <div
                            class="absolute inset-y-0 left-0 w-16 animate-transcribe-progress rounded-full bg-[linear-gradient(90deg,transparent,rgba(255,255,255,0.5),transparent)]"
                        ></div>
                    </div>
                </div>
            </aside>
        </main>
    </div>
</template>
