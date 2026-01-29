<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';

import CommandPalette from '@/components/CommandPalette.vue';
import ToastStack from '@/components/ToastStack.vue';
import { SidebarProvider } from '@/components/ui/sidebar';

interface Props {
    variant?: 'header' | 'sidebar';
}

defineProps<Props>();

const isOpen = usePage().props.sidebarOpen;
</script>

<template>
    <div v-if="variant === 'header'" class="flex min-h-screen w-full flex-col">
        <CommandPalette />
        <ToastStack />
        <slot />
    </div>
    <SidebarProvider v-else :default-open="isOpen">
        <CommandPalette />
        <ToastStack />
        <slot />
    </SidebarProvider>
</template>
