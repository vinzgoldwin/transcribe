<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <div
    data-slot="card"
    :class="
      cn(
        'bg-card text-card-foreground flex flex-col gap-6 rounded-2xl border border-border/70 py-6 shadow-[0_18px_40px_-30px_rgba(15,23,42,0.3)]',
        props.class,
      )
    "
  >
    <slot />
  </div>
</template>
