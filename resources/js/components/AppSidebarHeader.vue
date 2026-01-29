<script setup lang="ts">
import { Search } from 'lucide-vue-next';

import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItemType } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItemType[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const openCommandPalette = () => {
    window.dispatchEvent(new CustomEvent('command-palette:open'));
};
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-[color:var(--border)]/70 bg-[color:var(--surface)]/60 px-6 backdrop-blur transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>
        <div class="ml-auto flex items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                class="h-9 rounded-full px-3 text-xs font-semibold uppercase tracking-[0.2em]"
                type="button"
                @click="openCommandPalette"
            >
                <Search class="h-4 w-4" />
                Search
            </Button>
        </div>
    </header>
</template>
