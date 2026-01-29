<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
</script>

<template>
    <div
        class="flex aspect-square size-9 items-center justify-center rounded-[10px] bg-[var(--accent)] text-white shadow-[0_10px_20px_-14px_rgba(15,23,42,0.4)]"
    >
        <AppLogoIcon class="size-5 fill-current text-white" />
    </div>
    <div class="ml-2 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold text-[var(--text)]"
            >Laravel Starter Kit</span
        >
    </div>
</template>
