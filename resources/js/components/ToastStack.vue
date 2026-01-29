<script setup lang="ts">
import { TransitionGroup } from 'vue';

import { useToasts } from '@/composables/useToasts';

const { toasts, removeToast } = useToasts();

const variantStyles: Record<string, string> = {
    default:
        'border-[color:var(--border)]/70 bg-[color:var(--surface)]/90 text-[var(--text)]',
    success: 'border-emerald-200/70 bg-emerald-50/90 text-emerald-700',
    warning: 'border-amber-200/70 bg-amber-50/90 text-amber-700',
    error: 'border-red-200/70 bg-red-50/90 text-red-700',
};
</script>

<template>
    <Teleport to="body">
        <div
            class="fixed right-6 top-6 z-50 flex w-full max-w-sm flex-col gap-2"
        >
            <TransitionGroup
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0 translate-y-2"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 translate-y-2"
            >
                <button
                    v-for="toast in toasts"
                    :key="toast.id"
                    type="button"
                    class="flex w-full items-center justify-between gap-3 rounded-2xl border px-4 py-3 text-left text-sm shadow-[0_18px_40px_-28px_rgba(15,23,42,0.35)] backdrop-blur"
                    :class="variantStyles[toast.variant ?? 'default']"
                    @click="removeToast(toast.id)"
                >
                    <span class="font-medium">{{ toast.message }}</span>
                    <span class="text-xs uppercase tracking-[0.2em] opacity-60"
                        >Close</span
                    >
                </button>
            </TransitionGroup>
        </div>
    </Teleport>
</template>
