<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { dashboard } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';

interface CommandItem {
    id: string;
    label: string;
    description?: string;
    href?: string;
    category: string;
    shortcut?: string;
}

const open = ref(false);
const query = ref('');
const inputRef = ref<HTMLInputElement | null>(null);
const page = usePage();

const baseItems = computed<CommandItem[]>(() => [
    {
        id: 'dashboard',
        label: 'Dashboard',
        description: 'Jump to the transcription queue',
        href: dashboard().url,
        category: 'Navigation',
        shortcut: 'G D',
    },
    {
        id: 'settings-profile',
        label: 'Profile settings',
        description: 'Update name and email',
        href: editProfile().url,
        category: 'Navigation',
        shortcut: 'G P',
    },
    {
        id: 'settings-appearance',
        label: 'Appearance settings',
        description: 'Theme and display preferences',
        href: editAppearance().url,
        category: 'Navigation',
        shortcut: 'G A',
    },
]);

const transcriptionItems = computed<CommandItem[]>(() => {
    const items: CommandItem[] = [];
    const props = page.props as Record<string, unknown>;
    const fallbackUrl =
        typeof window === 'undefined' ? '/' : window.location.pathname;

    if (Array.isArray(props.transcriptions)) {
        props.transcriptions.forEach((transcription) => {
            if (
                typeof transcription === 'object' &&
                transcription !== null &&
                'id' in transcription &&
                'filename' in transcription &&
                'show_url' in transcription
            ) {
                const record = transcription as {
                    id: string;
                    filename: string;
                    show_url: string;
                    status?: string;
                };

                items.push({
                    id: `transcription-${record.id}`,
                    label: record.filename,
                    description: record.status ?? 'Transcription',
                    href: record.show_url,
                    category: 'Transcriptions',
                });
            }
        });
    }

    if (
        props.transcription &&
        typeof props.transcription === 'object' &&
        'id' in props.transcription &&
        'filename' in props.transcription
    ) {
        const record = props.transcription as {
            id: string;
            filename: string;
            status?: string;
            show_url?: string;
        };

        items.unshift({
            id: `current-${record.id}`,
            label: record.filename,
            description: record.status ?? 'Current transcription',
            href: record.show_url ?? fallbackUrl,
            category: 'Transcriptions',
        });
    }

    return items;
});

const allItems = computed(() => [...baseItems.value, ...transcriptionItems.value]);

const filteredItems = computed(() => {
    const term = query.value.trim().toLowerCase();

    if (!term) {
        return allItems.value;
    }

    return allItems.value.filter((item) => {
        const haystack = `${item.label} ${item.description ?? ''}`.toLowerCase();
        return haystack.includes(term);
    });
});

const groupedItems = computed(() => {
    return filteredItems.value.reduce<Record<string, CommandItem[]>>(
        (groups, item) => {
            const group = item.category || 'General';
            if (!groups[group]) {
                groups[group] = [];
            }
            groups[group].push(item);
            return groups;
        },
        {},
    );
});

const runItem = (item: CommandItem) => {
    open.value = false;

    if (item.href) {
        router.visit(item.href);
    }
};

const handleInputKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        const item = filteredItems.value[0];
        if (item) {
            runItem(item);
        }
    }
};

const togglePalette = () => {
    open.value = !open.value;
};

const openPalette = () => {
    open.value = true;
};

const handleGlobalKeydown = (event: KeyboardEvent) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        togglePalette();
    }
};

const handleOpenEvent = () => {
    openPalette();
};

const handleToggleEvent = () => {
    togglePalette();
};

watch(open, (value) => {
    if (value) {
        nextTick(() => inputRef.value?.focus());
    } else {
        query.value = '';
    }
});

onMounted(() => {
    window.addEventListener('keydown', handleGlobalKeydown);
    window.addEventListener('command-palette:open', handleOpenEvent as EventListener);
    window.addEventListener('command-palette:toggle', handleToggleEvent as EventListener);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleGlobalKeydown);
    window.removeEventListener('command-palette:open', handleOpenEvent as EventListener);
    window.removeEventListener('command-palette:toggle', handleToggleEvent as EventListener);
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-xl">
            <DialogTitle class="sr-only">Command palette</DialogTitle>
            <div class="flex flex-col gap-4">
                <div class="flex items-center gap-3">
                    <Input
                        ref="inputRef"
                        v-model="query"
                        placeholder="Search transcriptions or navigate..."
                        class="h-11 rounded-full"
                        @keydown="handleInputKeydown"
                    />
                    <span
                        class="hidden rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)] px-3 py-2 text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground sm:inline-flex"
                    >
                        Esc
                    </span>
                </div>

                <div class="max-h-[360px] overflow-auto pr-1">
                    <div
                        v-if="filteredItems.length === 0"
                        class="rounded-2xl border border-dashed border-[color:var(--border)]/70 bg-[var(--surface)]/60 p-6 text-sm text-muted-foreground"
                    >
                        No matches. Try a different keyword.
                    </div>

                    <div v-else class="flex flex-col gap-4">
                        <div
                            v-for="(items, group) in groupedItems"
                            :key="group"
                            class="space-y-2"
                        >
                            <p class="text-[10px] font-semibold uppercase tracking-[0.3em] text-muted-foreground">
                                {{ group }}
                            </p>
                            <div class="space-y-2">
                                <button
                                    v-for="item in items"
                                    :key="item.id"
                                    type="button"
                                    class="flex w-full items-center justify-between gap-4 rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/80 px-4 py-3 text-left text-sm transition hover:border-[color:var(--border)] hover:bg-[var(--surface-2)]"
                                    @click="runItem(item)"
                                >
                                    <div>
                                        <p class="text-sm font-semibold text-[var(--text)]">
                                            {{ item.label }}
                                        </p>
                                        <p class="text-xs text-muted-foreground">
                                            {{ item.description }}
                                        </p>
                                    </div>
                                    <span
                                        v-if="item.shortcut"
                                        class="rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)] px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground"
                                    >
                                        {{ item.shortcut }}
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
