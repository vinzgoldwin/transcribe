<script setup lang="ts">
import { Monitor, Moon, Sun } from 'lucide-vue-next';

import { useAppearance } from '@/composables/useAppearance';

const { appearance, updateAppearance } = useAppearance();

const tabs = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
    { value: 'system', Icon: Monitor, label: 'System' },
] as const;
</script>

<template>
    <div
        class="inline-flex gap-1 rounded-full border border-[color:var(--border)]/70 bg-[var(--surface-2)] p-1 shadow-[inset_0_1px_0_rgba(255,255,255,0.6)]"
    >
        <button
            v-for="{ value, Icon, label } in tabs"
            :key="value"
            @click="updateAppearance(value)"
            :class="[
                'flex items-center gap-2 rounded-full px-4 py-2 text-xs font-semibold uppercase tracking-[0.18em] transition-all',
                appearance === value
                    ? 'bg-[var(--surface)] text-[var(--text)] shadow-[0_10px_20px_-14px_rgba(15,23,42,0.25)]'
                    : 'text-muted-foreground hover:bg-[var(--surface)]/70 hover:text-foreground',
            ]"
        >
            <component :is="Icon" class="h-4 w-4" />
            <span>{{ label }}</span>
        </button>
    </div>
</template>
