<script setup lang="ts">
interface Props {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
}

withDefaults(defineProps<Props>(), {
    variant: 'default',
});
</script>

<template>
    <header :class="variant === 'small' ? '' : 'mb-8 space-y-0.5'">
        <h2
            :class="
                variant === 'small'
                    ? 'mb-1 text-lg font-semibold tracking-[-0.01em]'
                    : 'text-2xl font-semibold tracking-[-0.015em]'
            "
        >
            {{ title }}
        </h2>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
    </header>
</template>
