<script setup lang="ts">
import { LinkComponentBaseProps, Method } from '@inertiajs/core';
import { Link } from '@inertiajs/vue3';

interface Props {
    href: LinkComponentBaseProps['href'];
    tabindex?: number;
    method?: Method;
    as?: string;
}

defineProps<Props>();
</script>

<template>
    <Link
        :href="href"
        :tabindex="tabindex"
        :method="method"
        :as="as"
        class="text-[var(--accent)] underline decoration-[color:var(--accent)]/40 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-[color:var(--accent)]"
    >
        <slot />
    </Link>
</template>
