<script setup lang="ts">
import { computed } from 'vue';

import { SidebarInset } from '@/components/ui/sidebar';

interface Props {
    variant?: 'header' | 'sidebar';
    class?: string;
}

const props = defineProps<Props>();
const className = computed(() => props.class);
</script>

<template>
    <SidebarInset v-if="props.variant === 'sidebar'" :class="className">
        <slot />
    </SidebarInset>
    <main
        v-else
        class="mx-auto flex h-full w-full max-w-6xl flex-1 flex-col gap-6 rounded-3xl px-6 pb-10 pt-6"
        :class="className"
    >
        <slot />
    </main>
</template>
